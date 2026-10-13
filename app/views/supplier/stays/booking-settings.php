<?php @$SECURE or die('Access Denied!'); ?>
<?php $s = (isset($site) && is_array($site)) ? $site : []; $stayName = $stay['name'] ?? 'Property'; $bookingSettings = function_exists('booking_get_settings') ? booking_get_settings($GLOBALS['db'] ?? null, (int) ($stay['id'] ?? 0)) : []; ?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?><?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?><div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div><?php unset($_SESSION['message']); ?><?php endif; ?>
  <div class="flex items-center justify-between"><h1 class="text-2xl font-bold text-gray-900">Guest Booking Settings</h1><a href="<?= root ?>supplier/stays/edit/<?= (int) ($stay['id'] ?? 0) ?>" class="text-sm text-blue-600 hover:underline">&larr; Back to property</a></div>
  <p class="text-sm text-gray-600">Configure how guests book directly on your website without creating an account</p>
  <form action="<?= root ?>supplier/stays/booking-settings-save" method="POST" class="space-y-6">
    <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= (int) ($stay['id'] ?? 0) ?>">
    <div class="card p-6 space-y-5">
      <h2 class="text-lg font-semibold text-gray-900">Enable Guest Bookings</h2>
      <label class="inline-flex items-center gap-2"><input type="checkbox" name="enabled" value="1" class="checkbox-input" <?= !empty($bookingSettings['enabled']) ? 'checked' : '' ?>><span class="text-sm text-gray-700">Allow guests to book directly without creating an account</span></label>
    </div>
    <div class="card p-6 space-y-5">
      <h2 class="text-lg font-semibold text-gray-900">Contact Information</h2>
      <p class="text-sm text-gray-600">Where guests can reach you about their bookings</p>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Booking Email</label><input type="email" name="contact_email" value="<?= htmlspecialchars($bookingSettings['contact_email'] ?? '') ?>" class="input" placeholder="bookings@hotel.com" required><p class="text-xs text-gray-500 mt-1">Shown on confirmation emails and booking page</p></div>
        <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Booking Phone</label><input type="text" name="contact_phone" value="<?= htmlspecialchars($bookingSettings['contact_phone'] ?? '') ?>" class="input" placeholder="+1 (555) 123-4567"><p class="text-xs text-gray-500 mt-1">Guests can call for booking questions</p></div>
      </div>
    </div>
    <div class="card p-6 space-y-5">
      <h2 class="text-lg font-semibold text-gray-900">Check-in Instructions</h2>
      <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Check-in Details</label><textarea name="checkin_instructions" rows="4" class="input" placeholder="Describe how guests check in (key pickup, door code, instructions, parking, etc.)"><?= htmlspecialchars($bookingSettings['checkin_instructions'] ?? '') ?></textarea><p class="text-xs text-gray-500 mt-1">Displayed in confirmation emails and booking page</p></div>
    </div>
    <div class="card p-6 space-y-5">
      <h2 class="text-lg font-semibold text-gray-900">Cancellation Policy</h2>
      <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Cancellation Terms</label><textarea name="cancellation_policy" rows="4" class="input" placeholder="Explain your cancellation and refund policy (e.g., free cancellation up to 7 days, 50% refund if cancelled 3-6 days before, etc.)"><?= htmlspecialchars($bookingSettings['cancellation_policy'] ?? '') ?></textarea><p class="text-xs text-gray-500 mt-1">Guests see this before confirming their booking</p></div>
    </div>
    <div class="card p-6 space-y-5">
      <h2 class="text-lg font-semibold text-gray-900">Booking Form Options</h2>
      <div class="space-y-3">
        <label class="inline-flex items-center gap-2"><input type="checkbox" name="require_phone" value="1" class="checkbox-input" <?= !empty($bookingSettings['require_phone']) ? 'checked' : '' ?>><span class="text-sm text-gray-700">Require phone number from guests</span></label>
        <label class="inline-flex items-center gap-2"><input type="checkbox" name="require_special_requests" value="1" class="checkbox-input" <?= !empty($bookingSettings['require_special_requests']) ? 'checked' : '' ?>><span class="text-sm text-gray-700">Include special requests field</span></label>
      </div>
    </div>
    <div class="card p-6 space-y-5">
      <h2 class="text-lg font-semibold text-gray-900">Payment Methods</h2>
      <p class="text-sm text-gray-600">Guests will pay securely through these methods</p>
      <div class="space-y-2">
        <label class="inline-flex items-center gap-2"><input type="checkbox" name="payment_methods[]" value="stripe" class="checkbox-input" checked disabled><span class="text-sm text-gray-700">Stripe (Credit/Debit Card)</span></label>
        <p class="text-xs text-gray-500">Payments are processed securely and deposited to your account</p>
      </div>
    </div>
    <div class="card p-4 flex gap-2"><button type="submit" class="btn indigo text-sm flex-1">Save Booking Settings</button></div>
  </form>
</div>
