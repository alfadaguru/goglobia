<?php @$SECURE or die('Access Denied!'); ?>
<?php $s = (isset($stay) && is_array($stay)) ? $stay : []; $booking = isset($booking) ? $booking : null; $bookingSettings = isset($bookingSettings) ? $bookingSettings : []; ?>
<div class="max-w-2xl mx-auto px-4 py-8 space-y-6">
  <div class="text-center space-y-2"><h1 class="text-2xl font-bold text-gray-900">My Booking</h1><p class="text-sm text-gray-600"><?= htmlspecialchars($s['name'] ?? '') ?></p></div>
  <?php if (!$booking): ?>
    <div class="card p-6 space-y-5">
      <h2 class="text-lg font-semibold text-gray-900">Retrieve Your Booking</h2>
      <p class="text-sm text-gray-600">Enter your email address to find your booking</p>
      <form action="<?= root ?>book/<?= htmlspecialchars($s['slug'] ?? '') ?>/my-booking" method="POST" class="space-y-4">
        <?= CSRF::tokenField() ?>
        <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">Email Address *</label><input type="email" name="guest_email" class="input" required></div>
        <button type="submit" class="btn indigo w-full">Find My Booking</button>
      </form>
    </div>
  <?php elseif (isset($_GET['verify'])): ?>
    <div class="card p-6 space-y-5">
      <h2 class="text-lg font-semibold text-gray-900">Verify Your Identity</h2>
      <p class="text-sm text-gray-600">We've sent a verification code to <?= htmlspecialchars($booking['guest_email']) ?></p>
      <form action="<?= root ?>book/<?= htmlspecialchars($s['slug'] ?? '') ?>/verify-booking" method="POST" class="space-y-4">
        <?= CSRF::tokenField() ?><input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>"><input type="hidden" name="guest_email" value="<?= htmlspecialchars($booking['guest_email']) ?>">
        <div class="form-control"><label class="block text-sm font-medium text-gray-700 mb-1">6-Digit Code *</label><input type="text" name="otp_code" class="input text-center text-2xl tracking-widest" maxlength="6" placeholder="000000" required></div>
        <button type="submit" class="btn indigo w-full">Verify</button>
        <p class="text-xs text-gray-500 text-center">Code expires in 10 minutes</p>
      </form>
    </div>
  <?php elseif (isset($_GET['details']) && isset($_SESSION['verified_booking_id'])): ?>
    <div class="space-y-5">
      <div class="card p-6 space-y-3">
        <h2 class="text-lg font-semibold text-gray-900">Booking Details</h2>
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div><p class="text-gray-500">Confirmation Number</p><p class="font-mono font-medium"><?= htmlspecialchars($booking['booking_reference']) ?></p></div>
          <div><p class="text-gray-500">Booking Date</p><p class="font-medium"><?= date('M d, Y', strtotime($booking['created_at'])) ?></p></div>
          <div><p class="text-gray-500">Guest Name</p><p class="font-medium"><?= htmlspecialchars($booking['guest_first_name'] . ' ' . $booking['guest_last_name']) ?></p></div>
          <div><p class="text-gray-500">Status</p><p class="font-medium"><?= htmlspecialchars(ucfirst($booking['booking_status'])) ?></p></div>
          <div><p class="text-gray-500">Check-in</p><p class="font-medium"><?= date('M d, Y', strtotime($booking['checkin_date'])) ?></p></div>
          <div><p class="text-gray-500">Check-out</p><p class="font-medium"><?= date('M d, Y', strtotime($booking['checkout_date'])) ?></p></div>
          <div><p class="text-gray-500">Guests</p><p class="font-medium"><?= ((int) $booking['num_adults']) . ' adult' . ((int) $booking['num_adults'] !== 1 ? 's' : '') . ($booking['num_children'] > 0 ? ', ' . ((int) $booking['num_children']) . ' child' . ((int) $booking['num_children'] !== 1 ? 'ren' : '') : '') ?></p></div>
          <div><p class="text-gray-500">Total Paid</p><p class="font-medium"><?= htmlspecialchars($booking['currency']) ?> <?= number_format($booking['total_price'], 2) ?></p></div>
        </div>
      </div>
      <?php if (!empty($bookingSettings['checkin_instructions'])): ?><div class="card p-6 space-y-3"><h3 class="font-semibold text-gray-900">Check-in Instructions</h3><p class="text-sm text-gray-700 whitespace-pre-line"><?= htmlspecialchars($bookingSettings['checkin_instructions']) ?></p></div><?php endif; ?>
      <div class="card p-6 space-y-3"><h3 class="font-semibold text-gray-900">Contact Information</h3><?php if (!empty($bookingSettings['contact_email'])): ?><p class="text-sm"><span class="text-gray-500">Email:</span> <a href="mailto:<?= htmlspecialchars($bookingSettings['contact_email']) ?>" class="text-blue-600 hover:underline"><?= htmlspecialchars($bookingSettings['contact_email']) ?></a></p><?php endif; ?><?php if (!empty($bookingSettings['contact_phone'])): ?><p class="text-sm"><span class="text-gray-500">Phone:</span> <a href="tel:<?= htmlspecialchars($bookingSettings['contact_phone']) ?>" class="text-blue-600 hover:underline"><?= htmlspecialchars($bookingSettings['contact_phone']) ?></a></p><?php endif; ?></div>
      <div class="card p-4 space-y-2"><a href="<?= root ?>book/<?= htmlspecialchars($s['slug'] ?? '') ?>/booking-pdf?ref=<?= htmlspecialchars($booking['booking_reference']) ?>" class="btn secondary w-full text-sm">Download as PDF</a><form action="<?= root ?>book/<?= htmlspecialchars($s['slug'] ?? '') ?>/booking-cancel" method="POST" class="w-full"><input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>"><button type="submit" class="btn tertiary w-full text-sm" onclick="return confirm('Are you sure? This action cannot be undone.')">Cancel Booking</button></form></div>
    </div>
  <?php else: ?>
    <div class="card p-6 text-center space-y-4"><p class="text-sm text-gray-600">To view your booking details, we need to verify your email address</p><form action="<?= root ?>book/<?= htmlspecialchars($s['slug'] ?? '') ?>/request-otp" method="POST"><input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>"><input type="hidden" name="guest_email" value="<?= htmlspecialchars($booking['guest_email']) ?>"><button type="submit" class="btn indigo">Send Verification Code</button></form></div>
  <?php endif; ?>
</div>
