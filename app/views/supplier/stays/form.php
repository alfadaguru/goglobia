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

  <form action="<?= $action ?>" method="POST" enctype="multipart/form-data" class="card p-6 space-y-5">
    <?= CSRF::tokenField() ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Property name *</label>
        <input type="text" name="hotel_name" required class="input"
               value="<?= htmlspecialchars($s['name'] ?? '') ?>" placeholder="e.g. Seaside Suites">
      </div>
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Location *</label>
        <input type="text" name="location" required class="input"
               value="<?= htmlspecialchars($s['location'] ?? '') ?>" placeholder="City / area">
      </div>
    </div>

    <div class="form-control">
      <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
      <input type="text" name="address" class="input" value="<?= htmlspecialchars($s['address'] ?? '') ?>">
    </div>

    <div class="form-control">
      <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
      <textarea name="description" rows="4" class="input"><?= htmlspecialchars($s['desc'] ?? '') ?></textarea>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Stars</label>
        <input type="number" name="stars" min="0" max="5" class="input" value="<?= (int) ($s['stars'] ?? 0) ?>">
      </div>
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
        <input type="text" name="currency" maxlength="3" class="input" value="<?= htmlspecialchars($s['currency'] ?? 'USD') ?>">
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

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Check-in</label>
        <input type="time" name="checkin_time" class="input" value="<?= htmlspecialchars(substr((string) ($s['checkin_time'] ?? '14:00:00'), 0, 5)) ?>">
      </div>
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Check-out</label>
        <input type="time" name="checkout_time" class="input" value="<?= htmlspecialchars(substr((string) ($s['checkout_time'] ?? '12:00:00'), 0, 5)) ?>">
      </div>
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Latitude</label>
        <input type="text" name="latitude" class="input" value="<?= htmlspecialchars($lat) ?>">
      </div>
      <div class="form-control">
        <label class="block text-sm font-medium text-gray-700 mb-1">Longitude</label>
        <input type="text" name="longitude" class="input" value="<?= htmlspecialchars($lng) ?>">
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
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

    <div class="form-control">
      <label class="block text-sm font-medium text-gray-700 mb-1">Cancellation policy</label>
      <textarea name="cancellation_policy" rows="2" class="input"><?= htmlspecialchars($s['cancellation_policy'] ?? '') ?></textarea>
    </div>

    <div class="form-control">
      <label class="block text-sm font-medium text-gray-700 mb-1">Photos<?= $isEdit ? ' (add more)' : '' ?></label>
      <input type="file" name="hotel_images[]" multiple accept="image/*" class="input">
      <p class="text-xs text-gray-500 mt-1">JPG/PNG/WEBP/GIF, up to 5MB each.</p>
      <?php
        $imgs = [];
        if ($isEdit && !empty($s['img'])) { $imgs = json_decode((string) $s['img'], true) ?: []; }
      ?>
      <?php if (!empty($imgs)): ?>
        <div class="flex flex-wrap gap-2 mt-3">
          <?php foreach ($imgs as $im): ?>
            <img src="<?= htmlspecialchars(root . ltrim((string) ($im['url'] ?? ''), '/')) ?>"
                 class="w-20 h-20 object-cover rounded border border-gray-200" alt="">
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <input type="hidden" name="amenity_ids" value="<?= htmlspecialchars($s['amenity_ids'] ?? '[]') ?>">

    <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100">
      <a href="<?= root ?>supplier/stays" class="btn secondary">Cancel</a>
      <button type="submit" class="btn emerald">
        <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">save</span> <?= $isEdit ? 'Save changes' : 'Create property' ?></span>
      </button>
    </div>
  </form>
</div>
