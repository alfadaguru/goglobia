<?php @$SECURE or die('Access Denied!'); ?>
<?php $s = (isset($stay) && is_array($stay)) ? $stay : []; $bookingSettings = isset($bookingSettings) ? $bookingSettings : []; ?>
<div class="max-w-2xl mx-auto px-4 py-8 space-y-6">
  <div class="text-center space-y-2"><h1 class="text-2xl font-bold text-gray-900">Complete Your Booking</h1><p class="text-sm text-gray-600"><?= htmlspecialchars($s['name'] ?? '') ?></p></div>
  <form action="<?= root ?>book/<?= htmlspecialchars($s['slug'] ?? '') ?>/checkout-process" method="POST" class="card p-6 space-y-5">
    <?= CSRF::tokenField() ?><input type="hidden" name="room_id" value="<?= (int) ($_POST['room_id'] ?? $_GET['room_id'] ?? 0) ?>"><input type="hidden" name="option_id" value="<?= (int) ($_POST['option_id'] ?? $_GET['option_id'] ?? 0) ?>"><input type="hidden" name="checkin_date" value="<?= htmlspecialchars($_POST['checkin_date'] ?? $_GET['checkin_date'] ?? '') ?>"><input type="hidden" name="checkout_date" value="<?= htmlspecialchars($_POST['checkout_date'] ?? $_GET['checkout_date'] ?? '') ?>">
    <h2 class="text-lg font-semibold text-gray-900">Your Information</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">First Name *</label><input type="text" name="first_name" class="input" required></div>
      <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label><input type="text" name="last_name" class="input" required></div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Email *</label><input type="email" name="email" class="input" required></div>
      <?php if (!empty($bookingSettings['require_phone'])): ?><div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Phone *</label><input type="text" name="phone" class="input" required></div><?php else: ?><div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Phone</label><input type="text" name="phone" class="input"></div><?php endif; ?>
    </div>
    <?php if (!empty($bookingSettings['require_special_requests'])): ?><div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Special Requests *</label><textarea name="special_requests" rows="3" class="input" placeholder="Let us know if you have any special requests..." required></textarea></div><?php endif; ?>
    <h2 class="text-lg font-semibold text-gray-900 mt-6">Booking Summary</h2>
    <div class="bg-gray-50 rounded p-4 space-y-2 text-sm"><div class="flex justify-between"><span class="text-gray-600">Check-in:</span><span class="font-medium"><?= htmlspecialchars($_POST['checkin_date'] ?? $_GET['checkin_date'] ?? '') ?></span></div><div class="flex justify-between"><span class="text-gray-600">Check-out:</span><span class="font-medium"><?= htmlspecialchars($_POST['checkout_date'] ?? $_GET['checkout_date'] ?? '') ?></span></div><div class="flex justify-between border-t border-gray-200 pt-2 mt-2"><span class="text-gray-600">Total:</span><span class="text-lg font-bold"><?= htmlspecialchars($bookingData['currency'] ?? 'USD') ?> <?= htmlspecialchars($bookingData['total_price'] ?? '0.00') ?></span></div></div>
    <?php if (!empty($bookingSettings['cancellation_policy'])): ?><div class="bg-amber-50 border border-amber-200 rounded p-4"><p class="text-xs font-medium text-amber-900 mb-1">Cancellation Policy</p><p class="text-xs text-amber-800 whitespace-pre-line"><?= htmlspecialchars($bookingSettings['cancellation_policy']) ?></p></div><?php endif; ?>
    <label class="inline-flex items-center gap-2"><input type="checkbox" name="agree_policy" value="1" class="checkbox-input" required><span class="text-xs text-gray-700">I agree to the terms and cancellation policy</span></label>
    <button type="submit" class="btn indigo w-full">Proceed to Payment</button>
  </form>
</div>
