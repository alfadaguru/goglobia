<?php
// app/views/admin/cruises/manage.php — add/edit form for an own-inventory cruise
@$SECURE or die('Access Denied!');
$c = isset($cruise) && is_array($cruise) ? $cruise : [];
$v = function (string $k, $d = '') use ($c) { return htmlspecialchars((string) ($c[$k] ?? $d), ENT_QUOTES); };
$isEdit = !empty($c['id']);
$roomTypes = ['interior' => 'Interior', 'oceanview' => 'Ocean view', 'balcony' => 'Balcony', 'suite' => 'Suite'];
$selRoom = (string) ($c['room_type'] ?? 'balcony');
?>
<div class="container my-4 max-w-4xl">
  <div class="flex items-center justify-between mb-4">
    <h1 class="text-2xl font-bold text-gray-900"><?= $isEdit ? 'Edit cruise' : 'Add cruise' ?></h1>
    <a href="<?= root . admin ?>/cruises" class="btn ghost text-sm">Back to list</a>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
      <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
      <div><?= htmlspecialchars((string) $_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <form action="<?= root . admin ?>/cruises/save" method="POST" class="space-y-5">
    <?= CSRF::tokenField() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><?php endif; ?>

    <div class="card p-5 space-y-4">
      <h3 class="font-semibold text-gray-900 text-sm">Basics</h3>
      <div class="grid md:grid-cols-2 gap-4">
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Cruise name *</label>
          <input type="text" name="name" class="input w-full" required maxlength="191" value="<?= $v('name') ?>" placeholder="e.g. Caribbean Paradise Getaway">
        </div>
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Slug <span class="text-gray-400">(auto if blank)</span></label>
          <input type="text" name="slug" class="input w-full" maxlength="191" value="<?= $v('slug') ?>" placeholder="caribbean-paradise-getaway">
        </div>
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Cruise line</label>
          <input type="text" name="cruise_line" class="input w-full" maxlength="120" value="<?= $v('cruise_line') ?>" placeholder="Royal Voyages">
        </div>
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Ship name</label>
          <input type="text" name="ship_name" class="input w-full" maxlength="120" value="<?= $v('ship_name') ?>" placeholder="MS Sea Breeze">
        </div>
      </div>
      <div class="form-control">
        <label class="block text-sm text-gray-600 mb-1">Description</label>
        <textarea name="description" rows="3" class="input w-full" placeholder="A short, appealing summary of the cruise."><?= htmlspecialchars((string) ($c['description'] ?? '')) ?></textarea>
      </div>
      <div class="form-control">
        <label class="block text-sm text-gray-600 mb-1">Image URL</label>
        <input type="text" name="img" class="input w-full" value="<?= $v('img') ?>" placeholder="https://… or uploads/…">
      </div>
    </div>

    <div class="card p-5 space-y-4">
      <h3 class="font-semibold text-gray-900 text-sm">Route &amp; schedule</h3>
      <div class="grid md:grid-cols-2 gap-4">
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Destination</label>
          <input type="text" name="destination" class="input w-full" maxlength="120" value="<?= $v('destination') ?>" placeholder="Caribbean">
        </div>
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Departure port</label>
          <input type="text" name="departure_port" class="input w-full" maxlength="120" value="<?= $v('departure_port') ?>" placeholder="Miami">
        </div>
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Nights</label>
          <input type="number" name="nights" min="1" max="120" class="input w-full" value="<?= $v('nights', '7') ?>">
        </div>
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Departure date</label>
          <input type="date" name="departure_date" class="input w-full" value="<?= $v('departure_date') ?>">
        </div>
      </div>
      <div class="form-control">
        <label class="block text-sm text-gray-600 mb-1">Itinerary <span class="text-gray-400">(one stop per line)</span></label>
        <textarea name="itinerary" rows="4" class="input w-full" placeholder="Day 1: Depart Miami&#10;Day 2: At sea&#10;Day 3: Nassau, Bahamas"><?= htmlspecialchars((string) ($c['itinerary'] ?? '')) ?></textarea>
      </div>
    </div>

    <div class="card p-5 space-y-4">
      <h3 class="font-semibold text-gray-900 text-sm">Pricing &amp; cabin</h3>
      <div class="grid md:grid-cols-4 gap-4">
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Currency</label>
          <input type="text" name="currency" class="input w-full" maxlength="10" value="<?= $v('currency', (string) ($GLOBALS['app']['default_currency'] ?? 'NGN')) ?>">
        </div>
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Price / guest *</label>
          <input type="number" name="price" step="0.01" min="0" class="input w-full" required value="<?= $v('price', '0') ?>">
        </div>
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Child price</label>
          <input type="number" name="child_price" step="0.01" min="0" class="input w-full" value="<?= $v('child_price', '0') ?>">
        </div>
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Max guests</label>
          <input type="number" name="max_guests" min="1" max="20" class="input w-full" value="<?= $v('max_guests', '4') ?>">
        </div>
      </div>
      <div class="grid md:grid-cols-3 gap-4">
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Room type</label>
          <select name="room_type" class="input w-full">
            <?php foreach ($roomTypes as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= $selRoom === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Stars (0–5)</label>
          <input type="number" name="stars" min="0" max="5" class="input w-full" value="<?= $v('stars', '0') ?>">
        </div>
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Sort order</label>
          <input type="number" name="order" class="input w-full" value="<?= $v('order', '0') ?>">
        </div>
      </div>
    </div>

    <div class="card p-5 space-y-4">
      <h3 class="font-semibold text-gray-900 text-sm">Details &amp; flags</h3>
      <div class="grid md:grid-cols-2 gap-4">
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Inclusions <span class="text-gray-400">(one per line)</span></label>
          <textarea name="inclusions" rows="3" class="input w-full" placeholder="All meals&#10;Entertainment&#10;Pool access"><?= htmlspecialchars((string) ($c['inclusions'] ?? '')) ?></textarea>
        </div>
        <div class="form-control">
          <label class="block text-sm text-gray-600 mb-1">Exclusions <span class="text-gray-400">(one per line)</span></label>
          <textarea name="exclusions" rows="3" class="input w-full" placeholder="Flights&#10;Gratuities&#10;Alcohol"><?= htmlspecialchars((string) ($c['exclusions'] ?? '')) ?></textarea>
        </div>
      </div>
      <div class="form-control">
        <label class="block text-sm text-gray-600 mb-1">Cancellation policy</label>
        <textarea name="cancellation_policy" rows="2" class="input w-full"><?= htmlspecialchars((string) ($c['cancellation_policy'] ?? '')) ?></textarea>
      </div>
      <div class="flex flex-wrap gap-6 pt-1">
        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="refundable" value="1" <?= !empty($c['refundable']) || !$isEdit ? 'checked' : '' ?>> Refundable</label>
        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="featured" value="1" <?= !empty($c['featured']) ? 'checked' : '' ?>> Featured</label>
        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="status" value="1" <?= (!$isEdit || !empty($c['status'])) ? 'checked' : '' ?>> Active (visible to customers)</label>
      </div>
    </div>

    <div class="flex items-center gap-3">
      <button type="submit" class="btn"><span class="material-symbols-outlined text-[18px]">save</span> <?= $isEdit ? 'Update cruise' : 'Add cruise' ?></button>
      <a href="<?= root . admin ?>/cruises" class="btn ghost">Cancel</a>
    </div>
  </form>
</div>
